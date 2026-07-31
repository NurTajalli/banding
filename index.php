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
$rows = null;
$stats = ['hunks' => 0, 'replace' => 0, 'delete' => 0, 'insert' => 0];
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $left = readSide('left_text', 'left_file');
    $right = readSide('right_text', 'right_file');

    if (strlen($left) > $maxUpload || strlen($right) > $maxUpload) {
        $error = 'One of the inputs is too large (max 2MB per side).';
    } else {
        $rows = Diff::compareLines($left, $right, $opts);
        foreach ($rows as $r) {
            switch ($r['tag']) {
                case 'insert':
                    $stats['hunks']++;
                    $stats['insert'] += count($r['right']);
                    break;
                case 'delete':
                    $stats['hunks']++;
                    $stats['delete'] += count($r['left']);
                    break;
                case 'replace':
                    $stats['hunks']++;
                    $stats['replace'] += max(count($r['left']), count($r['right']));
                    break;
            }
        }
    }
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function renderLine(?array $line, ?array $wordSegments = null): string
{
    if ($line === null) {
        return '<span class="empty">&nbsp;</span>';
    }
    if ($wordSegments === null) {
        return e($line['text']) === '' ? '&nbsp;' : e($line['text']);
    }
    $html = '';
    foreach ($wordSegments as $seg) {
        $text = e($seg['text']);
        if ($text === '') {
            continue;
        }
        $html .= $seg['tag'] === 'diff' ? '<mark>' . $text . '</mark>' : $text;
    }
    return $html === '' ? '&nbsp;' : $html;
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
    <div class="panes">
        <div class="pane-input">
            <div class="pane-head">
                <span>Original</span>
                <label class="file-btn">
                    Load file
                    <input type="file" id="left_file_picker" data-target="left_text">
                </label>
            </div>
            <textarea name="left_text" id="left_text" spellcheck="false" placeholder="Paste original PHP/HTML/CSS/JS code here&hellip;"><?= e($left) ?></textarea>
        </div>
        <div class="pane-input">
            <div class="pane-head">
                <span>Changed</span>
                <label class="file-btn">
                    Load file
                    <input type="file" id="right_file_picker" data-target="right_text">
                </label>
            </div>
            <textarea name="right_text" id="right_text" spellcheck="false" placeholder="Paste changed PHP/HTML/CSS/JS code here&hellip;"><?= e($right) ?></textarea>
        </div>
    </div>

    <div class="options">
        <label><input type="checkbox" name="ignore_whitespace" <?= $opts['ignore_whitespace'] ? 'checked' : '' ?>> Ignore whitespace differences</label>
        <label><input type="checkbox" name="ignore_blank_lines" <?= $opts['ignore_blank_lines'] ? 'checked' : '' ?>> Ignore blank lines</label>
        <label><input type="checkbox" name="ignore_case" <?= $opts['ignore_case'] ? 'checked' : '' ?>> Ignore case</label>
        <button type="submit" class="compare-btn">Compare</button>
        <button type="button" class="swap-btn" id="swap-btn" title="Swap Original/Changed">&#8646; Swap</button>
    </div>
</form>

<?php if ($error): ?>
    <p class="error"><?= e($error) ?></p>
<?php elseif ($rows !== null): ?>
    <div class="stats">
        <span class="badge badge-eq">Differences: <?= $stats['hunks'] ?></span>
        <span class="badge badge-mod">Modified lines: <?= $stats['replace'] ?></span>
        <span class="badge badge-del">Removed lines: <?= $stats['delete'] ?></span>
        <span class="badge badge-ins">Added lines: <?= $stats['insert'] ?></span>
        <label class="wrap-toggle"><input type="checkbox" id="wrap-toggle"> Wrap long lines</label>
    </div>
    <div class="diff-view" id="diff-view">
        <div class="diff-col" id="col-left">
            <?php foreach ($rows as $r): ?>
                <?php if ($r['tag'] === 'equal'): ?>
                    <?php foreach ($r['left'] as $ln): ?>
                        <div class="diff-row row-equal">
                            <span class="lineno"><?= $ln['no'] ?></span>
                            <span class="code"><?= renderLine($ln) ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php elseif ($r['tag'] === 'delete'): ?>
                    <?php foreach ($r['left'] as $ln): ?>
                        <div class="diff-row row-del">
                            <span class="lineno"><?= $ln['no'] ?></span>
                            <span class="code"><?= renderLine($ln) ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php elseif ($r['tag'] === 'insert'): ?>
                    <?php foreach ($r['right'] as $ln): ?>
                        <div class="diff-row row-blank"><span class="lineno"></span><span class="code">&nbsp;</span></div>
                    <?php endforeach; ?>
                <?php else: // replace ?>
                    <?php
                    $max = max(count($r['left']), count($r['right']));
                    for ($k = 0; $k < $max; $k++):
                        $l = $r['left'][$k] ?? null;
                        $rr = $r['right'][$k] ?? null;
                        $segLeft = null;
                        if ($l !== null && $rr !== null) {
                            [$segLeft, ] = Diff::compareWords($l['text'], $rr['text']);
                        }
                    ?>
                        <div class="diff-row <?= $l !== null ? 'row-mod' : 'row-blank' ?>">
                            <span class="lineno"><?= $l['no'] ?? '' ?></span>
                            <span class="code"><?= $l !== null ? renderLine($l, $segLeft) : '&nbsp;' ?></span>
                        </div>
                    <?php endfor; ?>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <div class="diff-col" id="col-right">
            <?php foreach ($rows as $r): ?>
                <?php if ($r['tag'] === 'equal'): ?>
                    <?php foreach ($r['right'] as $ln): ?>
                        <div class="diff-row row-equal">
                            <span class="lineno"><?= $ln['no'] ?></span>
                            <span class="code"><?= renderLine($ln) ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php elseif ($r['tag'] === 'insert'): ?>
                    <?php foreach ($r['right'] as $ln): ?>
                        <div class="diff-row row-ins">
                            <span class="lineno"><?= $ln['no'] ?></span>
                            <span class="code"><?= renderLine($ln) ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php elseif ($r['tag'] === 'delete'): ?>
                    <?php foreach ($r['left'] as $ln): ?>
                        <div class="diff-row row-blank"><span class="lineno"></span><span class="code">&nbsp;</span></div>
                    <?php endforeach; ?>
                <?php else: // replace ?>
                    <?php
                    $max = max(count($r['left']), count($r['right']));
                    for ($k = 0; $k < $max; $k++):
                        $l = $r['left'][$k] ?? null;
                        $rr = $r['right'][$k] ?? null;
                        $segRight = null;
                        if ($l !== null && $rr !== null) {
                            [, $segRight] = Diff::compareWords($l['text'], $rr['text']);
                        }
                    ?>
                        <div class="diff-row <?= $rr !== null ? 'row-mod' : 'row-blank' ?>">
                            <span class="lineno"><?= $rr['no'] ?? '' ?></span>
                            <span class="code"><?= $rr !== null ? renderLine($rr, $segRight) : '&nbsp;' ?></span>
                        </div>
                    <?php endfor; ?>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<script src="assets/app.js"></script>
</body>
</html>
