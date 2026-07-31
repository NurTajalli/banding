(function () {
    // Load a picked file's text straight into the matching textarea, client-side.
    document.querySelectorAll('input[type=file]').forEach(function (input) {
        input.addEventListener('change', function () {
            var file = input.files[0];
            if (!file) return;
            var targetId = input.getAttribute('data-target');
            var target = document.getElementById(targetId);
            var reader = new FileReader();
            reader.onload = function (e) {
                target.value = e.target.result;
                // The file's content now lives in the textarea; clear the
                // input so a later resubmission (Swap, Copy, manual edits)
                // sends the current textarea value instead of re-sending
                // this original file untouched (the server prefers $_FILES
                // over the posted textarea field when both are present).
                input.value = '';
            };
            reader.readAsText(file);
        });
    });

    // Swap the two panes.
    var swapBtn = document.getElementById('swap-btn');
    if (swapBtn) {
        swapBtn.addEventListener('click', function () {
            var left = document.getElementById('left_text');
            var right = document.getElementById('right_text');
            var tmp = left.value;
            left.value = right.value;
            right.value = tmp;
        });
    }

    // Keep the two diff columns scrolling together.
    var colLeft = document.getElementById('col-left');
    var colRight = document.getElementById('col-right');
    if (colLeft && colRight) {
        var syncing = false;
        var link = function (from, to) {
            from.addEventListener('scroll', function () {
                if (syncing) return;
                syncing = true;
                // scroll-behavior:smooth on .diff-col also applies to plain
                // scrollTop/scrollLeft assignment, not just scrollTo()/
                // scrollIntoView() - without forcing 'instant' here, the
                // follower column would animate its catch-up on every
                // scroll event and visibly lag behind the one being dragged.
                to.scrollTo({ top: from.scrollTop, left: from.scrollLeft, behavior: 'instant' });
                syncing = false;
            });
        };
        link(colLeft, colRight);
        link(colRight, colLeft);
    }

    var wrapToggle = document.getElementById('wrap-toggle');
    if (wrapToggle) {
        wrapToggle.addEventListener('change', function () {
            document.body.classList.toggle('wrap-lines', wrapToggle.checked);
        });
    }

    // Prev/Next diff navigation. The server marks the first row of each
    // change block with id="hunk-left-N" / "hunk-right-N" (N = hunk index,
    // shared between the two columns). Navigation is position-aware (based
    // on current scroll position, not a stored counter) so it stays correct
    // even if the user scrolls manually in between clicks.
    var prevBtn = document.getElementById('prev-diff-btn');
    var nextBtn = document.getElementById('next-diff-btn');
    if ((prevBtn || nextBtn) && colLeft) {
        var hunkEls = Array.prototype.slice.call(colLeft.querySelectorAll('[id^="hunk-left-"]'));

        var flash = function (el) {
            if (!el) return;
            el.classList.remove('jump-flash');
            void el.offsetWidth; // restart the animation if the same row is hit again
            el.classList.add('jump-flash');
            setTimeout(function () { el.classList.remove('jump-flash'); }, 900);
        };

        var jumpTo = function (el) {
            if (!el) return;
            // Instant, not smooth: on large files the jump distance can be
            // tens of thousands of pixels, where a smooth animation takes
            // several seconds and can look like nothing happened. The pink
            // flash below is what gives the "you landed here" feedback.
            el.scrollIntoView({ block: 'start', behavior: 'instant' });
            flash(el);
            flash(document.getElementById('hunk-right-' + el.id.replace('hunk-left-', '')));
        };

        if (nextBtn) {
            nextBtn.addEventListener('click', function () {
                var top = colLeft.scrollTop + 2;
                for (var i = 0; i < hunkEls.length; i++) {
                    if (hunkEls[i].offsetTop > top) { jumpTo(hunkEls[i]); return; }
                }
            });
        }
        if (prevBtn) {
            prevBtn.addEventListener('click', function () {
                var top = colLeft.scrollTop - 2;
                for (var i = hunkEls.length - 1; i >= 0; i--) {
                    if (hunkEls[i].offsetTop < top) { jumpTo(hunkEls[i]); return; }
                }
            });
        }
    }

    // Copy a hunk's lines from one side to the other, then re-run Compare so
    // the view refreshes with that difference resolved. window.__hunks[N]
    // holds, per hunk, the 0-based line range and text for each side - the
    // server already worked out where blank/insertion points fall.
    document.querySelectorAll('.copy-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var hunk = window.__hunks && window.__hunks[parseInt(btn.getAttribute('data-hunk'), 10)];
            if (!hunk) return;

            var normalize = function (s) { return s.replace(/\r\n|\r/g, '\n').split('\n'); };
            var dir = btn.getAttribute('data-dir');

            if (dir === 'right') {
                var rightTa = document.getElementById('right_text');
                var rightLines = normalize(rightTa.value);
                rightLines.splice.apply(rightLines, [hunk.rightStart, hunk.rightCount].concat(hunk.leftLines));
                rightTa.value = rightLines.join('\n');
            } else {
                var leftTa = document.getElementById('left_text');
                var leftLines = normalize(leftTa.value);
                leftLines.splice.apply(leftLines, [hunk.leftStart, hunk.leftCount].concat(hunk.rightLines));
                leftTa.value = leftLines.join('\n');
            }

            var form = document.getElementById('compare-form');
            if (form.requestSubmit) form.requestSubmit(); else form.submit();
        });
    });

    // "Edit" switches a pane back from the diff view to its raw textarea.
    document.querySelectorAll('.edit-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var pane = document.getElementById(btn.getAttribute('data-target'));
            if (!pane) return;
            var textarea = pane.querySelector('textarea');
            var diffWrap = pane.querySelector('.diff-wrap');
            if (textarea) textarea.classList.remove('is-hidden');
            if (diffWrap) diffWrap.classList.add('is-hidden');
        });
    });
})();
