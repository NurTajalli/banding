<?php
declare(strict_types=1);

require_once __DIR__ . '/Diff.php';

$maxUpload = 2 * 1024 * 1024; // 2MB per side, plenty for source files

function readSide(string $textField, string $fileField): string
{
    if (!empty($_FILES[$fileField]['tmp_name']) && is_uploaded_file($_FILES[$fileField]['tmp_name'])) {
        $content = file_get_contents($_FILES[$fileField]['tmp_name']);
        return $content === false ? '' : $content;
    }
    return $_POST[$textField] ?? '';
}

$opts = [
    'ignore_whitespace' => isset($_POST['ignore_whitespace']),
    'ignore_blank_lines' => isset($_POST['ignore_blank_lines']),
    'ignore_case' => isset($_POST['ignore_case']),
];

$left = '';
$right = '';
$renderRows = null;
$minimap = [];
$totalRows = 0;
$stats = ['hunks' => 0, 'replace' => 0, 'delete' => 0, 'insert' => 0];
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $left = readSide('left_text', 'left_file');
    $right = readSide('right_text', 'right_file');

    if (strlen($left) > $maxUpload || strlen($right) > $maxUpload) {
        $error = 'One of the inputs is too large (max 2MB per side).';
    } else {
        $rows = Diff::compareLines($left, $right, $opts);

        // Single pass: build one row per rendered line (used by both columns)
        // and the minimap hunk list, computing word-level diffs exactly once
        // per modified line pair instead of once per column.
        $renderRows = [];
        foreach ($rows as $r) {
            $blockStart = count($renderRows);

            switch ($r['tag']) {
                case 'equal':
                    foreach ($r['left'] as $idx => $ln) {
                        $rn = $r['right'][$idx];
                        $renderRows[] = [
                            'lclass' => 'row-equal', 'lno' => $ln['no'], 'ltext' => $ln['text'], 'lseg' => null,
                            'rclass' => 'row-equal', 'rno' => $rn['no'], 'rtext' => $rn['text'], 'rseg' => null,
                        ];
                    }
                    break;
                case 'delete':
                    foreach ($r['left'] as $ln) {
                        $renderRows[] = [
                            'lclass' => 'row-del', 'lno' => $ln['no'], 'ltext' => $ln['text'], 'lseg' => null,
                            'rclass' => 'row-blank', 'rno' => null, 'rtext' => null, 'rseg' => null,
                        ];
                    }
                    $stats['hunks']++;
                    $stats['delete'] += count($r['left']);
                    break;
                case 'insert':
                    foreach ($r['right'] as $rn) {
                        $renderRows[] = [
                            'lclass' => 'row-blank', 'lno' => null, 'ltext' => null, 'lseg' => null,
                            'rclass' => 'row-ins', 'rno' => $rn['no'], 'rtext' => $rn['text'], 'rseg' => null,
                        ];
                    }
                    $stats['hunks']++;
                    $stats['insert'] += count($r['right']);
                    break;
                case 'replace':
                    $max = max(count($r['left']), count($r['right']));
                    for ($k = 0; $k < $max; $k++) {
                        $l = $r['left'][$k] ?? null;
                        $rr = $r['right'][$k] ?? null;
                        $segLeft = null;
                        $segRight = null;
                        if ($l !== null && $rr !== null) {
                            [$segLeft, $segRight] = Diff::compareWords($l['text'], $rr['text']);
                        }
                        $renderRows[] = [
                            'lclass' => $l !== null ? 'row-mod' : 'row-blank', 'lno' => $l['no'] ?? null, 'ltext' => $l['text'] ?? null, 'lseg' => $segLeft,
                            'rclass' => $rr !== null ? 'row-mod' : 'row-blank', 'rno' => $rr['no'] ?? null, 'rtext' => $rr['text'] ?? null, 'rseg' => $segRight,
                        ];
                    }
                    $stats['hunks']++;
                    $stats['replace'] += $max;
                    break;
            }

            if ($r['tag'] !== 'equal') {
                $minimap[] = ['start' => $blockStart, 'count' => count($renderRows) - $blockStart, 'type' => $r['tag']];
            }
        }
        $totalRows = count($renderRows);
    }
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function renderCode(?string $text, ?array $wordSegments): string
{
    if ($text === null) {
        return '&nbsp;';
    }
    if ($wordSegments === null) {
        return e($text) === '' ? '&nbsp;' : e($text);
    }
    $html = '';
    foreach ($wordSegments as $seg) {
        $t = e($seg['text']);
        if ($t === '') {
            continue;
        }
        $html .= $seg['tag'] === 'diff' ? '<mark>' . $t . '</mark>' : $t;
    }
    return $html === '' ? '&nbsp;' : $html;
}

function renderMinimap(array $minimap, int $totalRows): string
{
    if ($totalRows === 0) {
        return '';
    }
    $html = '';
    foreach ($minimap as $m) {
        $top = ($m['start'] / $totalRows) * 100;
        $height = max(($m['count'] / $totalRows) * 100, 0.35);
        $cls = $m['type'] === 'replace' ? 'mark-mod' : ($m['type'] === 'delete' ? 'mark-del' : 'mark-ins');
        $html .= sprintf('<div class="mark %s" style="top:%.3f%%;height:%.3f%%"></div>', $cls, $top, $height);
    }
    return $html;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PHP Code Compare</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
    <h1>PHP Code Compare</h1>
    <p class="subtitle">Side-by-side diff for PHP, HTML, CSS &amp; JS source &mdash; no install required.</p>
</header>

<form method="post" enctype="multipart/form-data" id="compare-form">
    <div class="options">
        <label><input type="checkbox" name="ignore_whitespace" <?= $opts['ignore_whitespace'] ? 'checked' : '' ?>> Ignore whitespace differences</label>
        <label><input type="checkbox" name="ignore_blank_lines" <?= $opts['ignore_blank_lines'] ? 'checked' : '' ?>> Ignore blank lines</label>
        <label><input type="checkbox" name="ignore_case" <?= $opts['ignore_case'] ? 'checked' : '' ?>> Ignore case</label>
        <button type="submit" class="compare-btn">Compare</button>
        <button type="button" class="swap-btn" id="swap-btn" title="Swap Original/Changed">&#8646; Swap</button>
        <?php if ($renderRows !== null): ?>
            <label><input type="checkbox" id="wrap-toggle"> Wrap long lines</label>
            <span class="badge badge-eq">Differences: <?= $stats['hunks'] ?></span>
            <span class="badge badge-mod">Modified: <?= $stats['replace'] ?></span>
            <span class="badge badge-del">Removed: <?= $stats['delete'] ?></span>
            <span class="badge badge-ins">Added: <?= $stats['insert'] ?></span>
        <?php endif; ?>
    </div>

    <?php if ($error): ?>
        <p class="error"><?= e($error) ?></p>
    <?php endif; ?>

    <div class="panes">
        <div class="pane" id="pane-left">
            <div class="pane-head">
                <span>Original</span>
                <?php if ($renderRows !== null): ?>
                    <button type="button" class="edit-btn" data-target="pane-left">Edit</button>
                <?php endif; ?>
                <label class="file-btn">
                    Load file
                    <input type="file" id="left_file_picker" data-target="left_text">
                </label>
            </div>
            <textarea name="left_text" id="left_text" spellcheck="false" placeholder="Paste original PHP/HTML/CSS/JS code here&hellip;" class="<?= $renderRows !== null ? 'is-hidden' : '' ?>"><?= e($left) ?></textarea>
            <?php if ($renderRows !== null): ?>
                <div class="diff-wrap">
                    <div class="diff-col" id="col-left">
                        <?php foreach ($renderRows as $row): ?>
                            <div class="diff-row <?= $row['lclass'] ?>">
                                <span class="lineno"><?= $row['lno'] ?? '' ?></span>
                                <span class="code"><?= renderCode($row['ltext'], $row['lseg']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="diff-minimap"><?= renderMinimap($minimap, $totalRows) ?></div>
                </div>
            <?php endif; ?>
        </div>
        <div class="pane" id="pane-right">
            <div class="pane-head">
                <span>Changed</span>
                <?php if ($renderRows !== null): ?>
                    <button type="button" class="edit-btn" data-target="pane-right">Edit</button>
                <?php endif; ?>
                <label class="file-btn">
                    Load file
                    <input type="file" id="right_file_picker" data-target="right_text">
                </label>
            </div>
            <textarea name="right_text" id="right_text" spellcheck="false" placeholder="Paste changed PHP/HTML/CSS/JS code here&hellip;" class="<?= $renderRows !== null ? 'is-hidden' : '' ?>"><?= e($right) ?></textarea>
            <?php if ($renderRows !== null): ?>
                <div class="diff-wrap">
                    <div class="diff-col" id="col-right">
                        <?php foreach ($renderRows as $row): ?>
                            <div class="diff-row <?= $row['rclass'] ?>">
                                <span class="lineno"><?= $row['rno'] ?? '' ?></span>
                                <span class="code"><?= renderCode($row['rtext'], $row['rseg']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="diff-minimap"><?= renderMinimap($minimap, $totalRows) ?></div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</form>

<script src="assets/app.js"></script>
</body>
</html>
