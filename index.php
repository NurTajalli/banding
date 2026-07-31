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
        $hunkStarts = []; // rowIndex => hunkIndex, for the Prev/Next navigation buttons
        $hunkData = [];   // hunkIndex => copy-to-left/right metadata for the JS copy buttons
        $lastLeftNo = 0;
        $lastRightNo = 0;
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
                            'rclass' => 'row-blank-del', 'rno' => null, 'rtext' => null, 'rseg' => null,
                        ];
                    }
                    break;
                case 'insert':
                    foreach ($r['right'] as $rn) {
                        $renderRows[] = [
                            'lclass' => 'row-blank-ins', 'lno' => null, 'ltext' => null, 'lseg' => null,
                            'rclass' => 'row-ins', 'rno' => $rn['no'], 'rtext' => $rn['text'], 'rseg' => null,
                        ];
                    }
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
                            'lclass' => $l !== null ? 'row-mod' : 'row-blank-mod', 'lno' => $l['no'] ?? null, 'ltext' => $l['text'] ?? null, 'lseg' => $segLeft,
                            'rclass' => $rr !== null ? 'row-mod' : 'row-blank-mod', 'rno' => $rr['no'] ?? null, 'rtext' => $rr['text'] ?? null, 'rseg' => $segRight,
                        ];
                    }
                    break;
            }

            if ($r['tag'] !== 'equal') {
                $hunkStarts[$blockStart] = count($minimap);
                $minimap[] = ['start' => $blockStart, 'count' => count($renderRows) - $blockStart, 'type' => $r['tag']];
                $hunkData[] = [
                    'leftStart' => count($r['left']) > 0 ? ($r['left'][0]['no'] - 1) : $lastLeftNo,
                    'leftCount' => count($r['left']),
                    'leftLines' => array_column($r['left'], 'text'),
                    'rightStart' => count($r['right']) > 0 ? ($r['right'][0]['no'] - 1) : $lastRightNo,
                    'rightCount' => count($r['right']),
                    'rightLines' => array_column($r['right'], 'text'),
                ];
            }

            $lastLeftNo += count($r['left']);
            $lastRightNo += count($r['right']);
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
    <h1>BANDING: CODE COMPARE</h1>
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
            <div class="nav-group">
                <button type="button" class="nav-btn" id="prev-diff-btn" title="Previous difference">&#8593; Previous</button>
                <button type="button" class="nav-btn" id="next-diff-btn" title="Next difference">&#8595; Next</button>
            </div>
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
                        <?php foreach ($renderRows as $i => $row): ?>
                            <?php $hIdx = $hunkStarts[$i] ?? null; ?>
                            <div class="diff-row <?= $row['lclass'] ?>"<?= $hIdx !== null ? ' id="hunk-left-' . $hIdx . '"' : '' ?>>
                                <span class="lineno"><?= $row['lno'] ?? '' ?></span>
                                <span class="hunk-action"><?php if ($hIdx !== null): ?><button type="button" class="copy-btn" data-hunk="<?= $hIdx ?>" data-dir="right" title="Copy this change to the Changed side">&#9654;</button><?php endif; ?></span>
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
                        <?php foreach ($renderRows as $i => $row): ?>
                            <?php $hIdx = $hunkStarts[$i] ?? null; ?>
                            <div class="diff-row <?= $row['rclass'] ?>"<?= $hIdx !== null ? ' id="hunk-right-' . $hIdx . '"' : '' ?>>
                                <span class="lineno"><?= $row['rno'] ?? '' ?></span>
                                <span class="hunk-action"><?php if ($hIdx !== null): ?><button type="button" class="copy-btn" data-hunk="<?= $hIdx ?>" data-dir="left" title="Copy this change to the Original side">&#9664;</button><?php endif; ?></span>
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

<?php if ($renderRows !== null): ?>
<script>window.__hunks = <?= json_encode($hunkData) ?>;</script>
<?php endif; ?>
<script src="assets/app.js"></script>
</body>
</html>
