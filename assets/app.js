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
                to.scrollTop = from.scrollTop;
                to.scrollLeft = from.scrollLeft;
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
